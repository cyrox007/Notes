        <main class="main">
            <form action="" method="post">
                <input type="text" name="name" placeholder="Введите название заметки" required>
                <button type="submit">+</button>
            </form>
        </main>
        <hr>
        <section class="block-notes">
            <?php 
                foreach($data['files'] as $file): 
                mb_internal_encoding("UTF-8");
                // сколько знаков надо убрать сначала - отрезаем в имени дату и время
                $fname = mb_substr($file, 16);
                // сколько знаков надо убрать в конце строки - отрезаем расширение .txt
                $fname = mb_substr($fname, 0, -4); 
            ?> 
            <div class="note">
                <div class="note-l">
                    <i class="fa-solid fa-pen"></i>
                    <a href="/edit/<? echo "{$file}"?>" class="note-link"><? echo "{$fname}"?></a>
                </div>
                <div class="note-del">
                    <a href="/delete/<? echo "{$file}"?>"><i class="fa-solid fa-trash"></a></i>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="page-pagination">
                <button class="btn-page">1</button>
                <button class="btn-page">2</button>
                <button class="btn-page">3</button>
            </div>
		</section>