(() => {
    'use strict';

    const QUIET_ZONE = 4;
    const ERROR_CORRECTION_BITS_M = 0;
    const VERSION_SPECS = [
        { version: 10, centers: [6, 28, 50], blocks: [[4, 69, 43], [1, 70, 44]] },
        { version: 11, centers: [6, 30, 54], blocks: [[1, 80, 50], [4, 81, 51]] },
        { version: 12, centers: [6, 32, 58], blocks: [[6, 58, 36], [2, 59, 37]] },
        { version: 13, centers: [6, 34, 62], blocks: [[8, 59, 37], [1, 60, 38]] },
        { version: 14, centers: [6, 26, 46, 66], blocks: [[4, 64, 40], [5, 65, 41]] },
        { version: 15, centers: [6, 26, 48, 70], blocks: [[5, 65, 41], [5, 66, 42]] },
    ];
    const generatorCache = new Map();

    function createGaloisTables() {
        const exp = new Uint16Array(512);
        const log = new Uint16Array(256);
        let value = 1;

        for (let index = 0; index < 255; index += 1) {
            exp[index] = value;
            log[value] = index;
            value <<= 1;
            if ((value & 0x100) !== 0) {
                value ^= 0x11d;
            }
        }
        for (let index = 255; index < exp.length; index += 1) {
            exp[index] = exp[index - 255];
        }

        return { exp, log };
    }

    const GF = createGaloisTables();

    function multiplyGalois(left, right) {
        if (left === 0 || right === 0) {
            return 0;
        }
        return GF.exp[GF.log[left] + GF.log[right]];
    }

    function reedSolomonGenerator(degree) {
        if (generatorCache.has(degree)) {
            return generatorCache.get(degree);
        }

        let polynomial = [1];
        for (let power = 0; power < degree; power += 1) {
            const next = new Array(polynomial.length + 1).fill(0);
            polynomial.forEach((coefficient, index) => {
                next[index] ^= coefficient;
                next[index + 1] ^= multiplyGalois(coefficient, GF.exp[power]);
            });
            polynomial = next;
        }

        generatorCache.set(degree, polynomial);
        return polynomial;
    }

    function reedSolomonRemainder(data, degree) {
        const generator = reedSolomonGenerator(degree);
        const remainder = new Array(degree).fill(0);

        data.forEach((byte) => {
            const factor = byte ^ remainder[0];
            remainder.shift();
            remainder.push(0);
            for (let index = 0; index < degree; index += 1) {
                remainder[index] ^= multiplyGalois(generator[index + 1], factor);
            }
        });

        return remainder;
    }

    function appendBits(bits, value, length) {
        for (let offset = length - 1; offset >= 0; offset -= 1) {
            bits.push((value >>> offset) & 1);
        }
    }

    function expandedBlocks(spec) {
        const result = [];
        spec.blocks.forEach(([count, totalCount, dataCount]) => {
            for (let index = 0; index < count; index += 1) {
                result.push({ totalCount, dataCount });
            }
        });
        return result;
    }

    function dataCodewordCount(spec) {
        return expandedBlocks(spec)
            .reduce((sum, block) => sum + block.dataCount, 0);
    }

    function selectVersion(byteLength) {
        for (const spec of VERSION_SPECS) {
            const capacityBits = dataCodewordCount(spec) * 8;
            if (4 + 16 + byteLength * 8 <= capacityBits) {
                return spec;
            }
        }
        throw new Error('TOTP URI слишком длинный для локального QR-кода');
    }

    function encodePayload(payload, spec) {
        const bytes = Array.from(new TextEncoder().encode(payload));
        const capacityBytes = dataCodewordCount(spec);
        const capacityBits = capacityBytes * 8;
        const bits = [];

        appendBits(bits, 0b0100, 4);
        appendBits(bits, bytes.length, 16);
        bytes.forEach((byte) => appendBits(bits, byte, 8));

        const terminatorLength = Math.min(4, capacityBits - bits.length);
        for (let index = 0; index < terminatorLength; index += 1) {
            bits.push(0);
        }
        while (bits.length % 8 !== 0) {
            bits.push(0);
        }

        const codewords = [];
        for (let offset = 0; offset < bits.length; offset += 8) {
            let value = 0;
            for (let index = 0; index < 8; index += 1) {
                value = (value << 1) | bits[offset + index];
            }
            codewords.push(value);
        }

        const pads = [0xec, 0x11];
        let padIndex = 0;
        while (codewords.length < capacityBytes) {
            codewords.push(pads[padIndex % pads.length]);
            padIndex += 1;
        }

        const blocks = [];
        let sourceOffset = 0;
        expandedBlocks(spec).forEach((block) => {
            const data = codewords.slice(sourceOffset, sourceOffset + block.dataCount);
            sourceOffset += block.dataCount;
            blocks.push({
                data,
                correction: reedSolomonRemainder(data, block.totalCount - block.dataCount),
            });
        });

        const result = [];
        const maxData = Math.max(...blocks.map((block) => block.data.length));
        const maxCorrection = Math.max(...blocks.map((block) => block.correction.length));

        for (let index = 0; index < maxData; index += 1) {
            blocks.forEach((block) => {
                if (index < block.data.length) {
                    result.push(block.data[index]);
                }
            });
        }
        for (let index = 0; index < maxCorrection; index += 1) {
            blocks.forEach((block) => {
                if (index < block.correction.length) {
                    result.push(block.correction[index]);
                }
            });
        }

        return result;
    }

    function bchDigit(value) {
        let digits = 0;
        while (value !== 0) {
            digits += 1;
            value >>>= 1;
        }
        return digits;
    }

    function bchRemainder(value, polynomial) {
        let remainder = value;
        while (bchDigit(remainder) >= bchDigit(polynomial)) {
            remainder ^= polynomial << (bchDigit(remainder) - bchDigit(polynomial));
        }
        return remainder;
    }

    function typeInfoBits(maskPattern) {
        const data = (ERROR_CORRECTION_BITS_M << 3) | maskPattern;
        return ((data << 10) | bchRemainder(data << 10, 0x537)) ^ 0x5412;
    }

    function versionInfoBits(version) {
        return (version << 12) | bchRemainder(version << 12, 0x1f25);
    }

    function maskApplies(pattern, row, column) {
        switch (pattern) {
            case 0: return (row + column) % 2 === 0;
            case 1: return row % 2 === 0;
            case 2: return column % 3 === 0;
            case 3: return (row + column) % 3 === 0;
            case 4: return (Math.floor(row / 2) + Math.floor(column / 3)) % 2 === 0;
            case 5: return (row * column) % 2 + (row * column) % 3 === 0;
            case 6: return ((row * column) % 2 + (row * column) % 3) % 2 === 0;
            case 7: return ((row + column) % 2 + (row * column) % 3) % 2 === 0;
            default: throw new Error('Некорректная маска QR-кода');
        }
    }

    function createMatrix(spec, codewords, maskPattern) {
        const size = spec.version * 4 + 17;
        const matrix = Array.from({ length: size }, () => new Array(size).fill(null));

        function addFinder(row, column) {
            for (let rowOffset = -1; rowOffset <= 7; rowOffset += 1) {
                const targetRow = row + rowOffset;
                if (targetRow < 0 || targetRow >= size) {
                    continue;
                }
                for (let columnOffset = -1; columnOffset <= 7; columnOffset += 1) {
                    const targetColumn = column + columnOffset;
                    if (targetColumn < 0 || targetColumn >= size) {
                        continue;
                    }
                    const dark = (
                        rowOffset >= 0 && rowOffset <= 6
                        && (columnOffset === 0 || columnOffset === 6)
                    ) || (
                        columnOffset >= 0 && columnOffset <= 6
                        && (rowOffset === 0 || rowOffset === 6)
                    ) || (
                        rowOffset >= 2 && rowOffset <= 4
                        && columnOffset >= 2 && columnOffset <= 4
                    );
                    matrix[targetRow][targetColumn] = dark;
                }
            }
        }

        addFinder(0, 0);
        addFinder(size - 7, 0);
        addFinder(0, size - 7);

        spec.centers.forEach((row) => {
            spec.centers.forEach((column) => {
                if (matrix[row][column] !== null) {
                    return;
                }
                for (let rowOffset = -2; rowOffset <= 2; rowOffset += 1) {
                    for (let columnOffset = -2; columnOffset <= 2; columnOffset += 1) {
                        matrix[row + rowOffset][column + columnOffset] = (
                            Math.abs(rowOffset) === 2
                            || Math.abs(columnOffset) === 2
                            || (rowOffset === 0 && columnOffset === 0)
                        );
                    }
                }
            });
        });

        for (let row = 8; row < size - 8; row += 1) {
            if (matrix[row][6] === null) {
                matrix[row][6] = row % 2 === 0;
            }
        }
        for (let column = 8; column < size - 8; column += 1) {
            if (matrix[6][column] === null) {
                matrix[6][column] = column % 2 === 0;
            }
        }

        const versionBits = versionInfoBits(spec.version);
        for (let index = 0; index < 18; index += 1) {
            const dark = ((versionBits >>> index) & 1) === 1;
            matrix[Math.floor(index / 3)][index % 3 + size - 11] = dark;
            matrix[index % 3 + size - 11][Math.floor(index / 3)] = dark;
        }

        const formatBits = typeInfoBits(maskPattern);
        for (let index = 0; index < 15; index += 1) {
            const dark = ((formatBits >>> index) & 1) === 1;
            if (index < 6) {
                matrix[index][8] = dark;
            } else if (index < 8) {
                matrix[index + 1][8] = dark;
            } else {
                matrix[size - 15 + index][8] = dark;
            }
        }
        for (let index = 0; index < 15; index += 1) {
            const dark = ((formatBits >>> index) & 1) === 1;
            if (index < 8) {
                matrix[8][size - index - 1] = dark;
            } else if (index < 9) {
                matrix[8][15 - index] = dark;
            } else {
                matrix[8][15 - index - 1] = dark;
            }
        }
        matrix[size - 8][8] = true;

        let direction = -1;
        let row = size - 1;
        let byteIndex = 0;
        let bitIndex = 7;

        for (let column = size - 1; column > 0; column -= 2) {
            if (column === 6) {
                column -= 1;
            }

            while (true) {
                for (let offset = 0; offset < 2; offset += 1) {
                    const targetColumn = column - offset;
                    if (matrix[row][targetColumn] !== null) {
                        continue;
                    }

                    let dark = false;
                    if (byteIndex < codewords.length) {
                        dark = ((codewords[byteIndex] >>> bitIndex) & 1) === 1;
                    }
                    if (maskApplies(maskPattern, row, targetColumn)) {
                        dark = !dark;
                    }
                    matrix[row][targetColumn] = dark;

                    bitIndex -= 1;
                    if (bitIndex < 0) {
                        byteIndex += 1;
                        bitIndex = 7;
                    }
                }

                row += direction;
                if (row < 0 || row >= size) {
                    row -= direction;
                    direction = -direction;
                    break;
                }
            }
        }

        return matrix;
    }

    function penaltyScore(matrix) {
        const size = matrix.length;
        let score = 0;

        function scoreRuns(line) {
            let local = 0;
            let runColor = line[0];
            let runLength = 1;
            for (let index = 1; index <= line.length; index += 1) {
                const color = index < line.length ? line[index] : null;
                if (color === runColor) {
                    runLength += 1;
                    continue;
                }
                if (runLength >= 5) {
                    local += 3 + (runLength - 5);
                }
                runColor = color;
                runLength = 1;
            }
            return local;
        }

        for (let row = 0; row < size; row += 1) {
            score += scoreRuns(matrix[row]);
        }
        for (let column = 0; column < size; column += 1) {
            score += scoreRuns(matrix.map((row) => row[column]));
        }

        for (let row = 0; row < size - 1; row += 1) {
            for (let column = 0; column < size - 1; column += 1) {
                const value = matrix[row][column];
                if (
                    matrix[row][column + 1] === value
                    && matrix[row + 1][column] === value
                    && matrix[row + 1][column + 1] === value
                ) {
                    score += 3;
                }
            }
        }

        const patternA = [true, false, true, true, true, false, true, false, false, false, false];
        const patternB = [false, false, false, false, true, false, true, true, true, false, true];

        function matches(line, offset, pattern) {
            for (let index = 0; index < pattern.length; index += 1) {
                if (line[offset + index] !== pattern[index]) {
                    return false;
                }
            }
            return true;
        }

        function scoreFinderLike(line) {
            let local = 0;
            for (let offset = 0; offset <= line.length - patternA.length; offset += 1) {
                if (matches(line, offset, patternA) || matches(line, offset, patternB)) {
                    local += 40;
                }
            }
            return local;
        }

        for (let row = 0; row < size; row += 1) {
            score += scoreFinderLike(matrix[row]);
        }
        for (let column = 0; column < size; column += 1) {
            score += scoreFinderLike(matrix.map((row) => row[column]));
        }

        let darkCount = 0;
        matrix.forEach((row) => row.forEach((dark) => {
            if (dark) {
                darkCount += 1;
            }
        }));
        const darkPercent = darkCount * 100 / (size * size);
        score += Math.floor(Math.abs(darkPercent - 50) / 5) * 10;

        return score;
    }

    function matrixFor(payload) {
        if (typeof payload !== 'string' || !payload.startsWith('otpauth://totp/')) {
            throw new Error('QR-код 2FA принимает только TOTP provisioning URI');
        }

        const bytes = new TextEncoder().encode(payload);
        const spec = selectVersion(bytes.length);
        const codewords = encodePayload(payload, spec);
        let best = null;
        let bestScore = Infinity;

        for (let maskPattern = 0; maskPattern < 8; maskPattern += 1) {
            const matrix = createMatrix(spec, codewords, maskPattern);
            const score = penaltyScore(matrix);
            if (score < bestScore) {
                best = matrix;
                bestScore = score;
            }
        }

        return best;
    }

    function createSvg(matrix) {
        const namespace = 'http://www.w3.org/2000/svg';
        const size = matrix.length;
        const canvasSize = size + QUIET_ZONE * 2;
        const svg = document.createElementNS(namespace, 'svg');
        svg.setAttribute('viewBox', `0 0 ${canvasSize} ${canvasSize}`);
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', 'QR-код для настройки двухфакторной аутентификации');
        svg.setAttribute('shape-rendering', 'crispEdges');
        svg.setAttribute('focusable', 'false');

        const background = document.createElementNS(namespace, 'rect');
        background.setAttribute('width', String(canvasSize));
        background.setAttribute('height', String(canvasSize));
        background.setAttribute('fill', '#fff');
        svg.append(background);

        let pathData = '';
        matrix.forEach((row, rowIndex) => {
            let column = 0;
            while (column < row.length) {
                if (!row[column]) {
                    column += 1;
                    continue;
                }
                const start = column;
                while (column < row.length && row[column]) {
                    column += 1;
                }
                const length = column - start;
                pathData += `M${start + QUIET_ZONE} ${rowIndex + QUIET_ZONE}h${length}v1h-${length}z`;
            }
        });

        const path = document.createElementNS(namespace, 'path');
        path.setAttribute('d', pathData);
        path.setAttribute('fill', '#000');
        svg.append(path);

        return svg;
    }

    function render(element) {
        const uri = String(element.dataset.otpauthUri || '');
        try {
            const matrix = matrixFor(uri);
            element.replaceChildren(createSvg(matrix));
            element.dataset.qrReady = 'true';
            element.removeAttribute('data-qr-error');
        } catch (error) {
            element.dataset.qrError = 'true';
            element.textContent = 'QR-код не удалось построить. Используйте секретный ключ ниже.';
        }
    }

    function renderAll(root = document) {
        root.querySelectorAll('[data-totp-qr]').forEach(render);
    }

    globalThis.WorkspaceTotpQr = Object.freeze({ matrixFor, renderAll });

    if (typeof document !== 'undefined') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => renderAll(), { once: true });
        } else {
            renderAll();
        }
    }
})();
