document.addEventListener("DOMContentLoaded", function () {
    let noteBlock = document.querySelector('.block-notes');
    let notes = document.querySelectorAll('.note');
    let pagination = document.querySelector('.page-pagination');

    let notesOnPage = 10;
    let countOfItems = Math.ceil(notes.length / notesOnPage);
    
    if (countOfItems > 1) {
        pagination.classList.add('active');
    }
    let pageNum = document.querySelectorAll('.btn-page');

    for (let item of pageNum) {
        item.addEventListener('click', function() {
            let pNum = +this.innerHTML;            

            let start = (pNum - 1) * notesOnPage;
            let end = start + notesOnPage;

            for (let i = start; i < end; i++) {
                noteBlock.prepend(notes[i]);
                console.log(notes[i]);
            }
            
        });
    }
});