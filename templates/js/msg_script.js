document.addEventListener('DOMContentLoaded', ()=>{
    let dialoguesEmpty = document.getElementById('dialogues-empty');
    let viewUsers = document.getElementById('view-users');

    if (dialoguesEmpty) {
        dialoguesEmpty.addEventListener('click', ()=>{
            dialoguesEmpty.style.display = 'none';
            viewUsers.style.display = 'flex';
        });
    }

    let dialogLink = document.querySelectorAll('.messager__contact_item');
    let msgView = document.getElementById('msg-view');
    dialogLink.forEach(element => {
        element.addEventListener('click', ()=>{
            let file = new XMLHttpRequest();
            file.open('post', element.getAttribute('data-href'), false);
            file.onreadystatechange = () => {
                if(file.readyState === 4) {
                    if(file.status === 200 || file.status == 0) {
                        var allText = file.responseText;
                        msgView.innerText = allText;
                    }
                }
            }
            file.send(null);
        });
    });
});