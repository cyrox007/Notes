document.addEventListener('DOMContentLoaded', ()=>{
    let dialoguesEmpty = document.getElementById('dialogues-empty');
    let viewUsers = document.getElementById('view-users');

    if (dialoguesEmpty) {
        dialoguesEmpty.addEventListener('click', ()=>{
            dialoguesEmpty.style.display = 'none';
            viewUsers.style.display = 'flex';
        });
    }
    let msgField = document.getElementById('msg-view');
    let dialogLink = document.querySelectorAll('.messager__contact_item');
    let msgView = document.getElementById('msg-view');
    dialogLink.forEach(element => {
        element.addEventListener('click', (e) => {
            listenerMsg(element);
        });
    });
    function listenerMsg(element) {
        let ajax = new XMLHttpRequest();
        ajax.open('GET', element.getAttribute('data-href'));
        console.log(element.getAttribute('data-href'));
        ajax.addEventListener("readystatechange", () => {
            if (ajax.readyState === 4 && ajax.status === 200) {
                let msgText = JSON.parse(ajax.responseText);
                msgText.forEach(element => {
                    let msg = document.createElement('div');
                    msg.classList.add('msg');
                    msgField.appendChild(msg);

                    msg.innerHTML = `<span>${element['first_name']}</span> ${element['message']} `;
                    
                    console.log(element);
                });
            }
        });
        ajax.send();
    }

    let sendBtn = document.getElementById('message-send');
    sendBtn.addEventListener('click', () => {
        
    });
});