document.addEventListener('DOMContentLoaded', ()=>{
    let dialoguesEmpty = document.getElementById('dialogues-empty');
    let viewUsers = document.getElementById('view-users');

    dialoguesEmpty.addEventListener('click', ()=>{
        dialoguesEmpty.style.display = 'none';
        viewUsers.style.display = 'flex';
    });
});