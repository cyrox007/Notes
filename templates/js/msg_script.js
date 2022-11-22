wspace.messeger = {
    data: {
        dialoguesEmpty: document.getElementById('dialogues-empty'),
        viewUsers: document.getElementById('view-users'),
        msgField: document.getElementById('msg-view'),
        dialogLink: document.querySelectorAll('.messager__contact_item'),
        msgView: document.getElementById('msg-view'),
        sendBtn: document.getElementById('message-send'),
    },
    handler: {
        getSelectedDialog: (dialoges) => {
            dialoges.forEach(elem=>{
                if (elem.classList.contains('selected'))
                    return elem;
            });
        },
        listenerMsg: async (element) => {
            if (element.classList.contains('selected')) {
                return;
            }

            if ((prevElem = wspace.messeger.handler.getSelectedDialog(wspace.messeger.data.dialogLink)) != null) {
                prevElem.classList.remove('selected');
            }
            
            element.classList.add('selected');
            let url = element.getAttribute('data-href');
            let response = await fetch(url);
    
            if (response.ok) {
                let json = await response.json();
                json.forEach(msg=>{
                    let msgContainer = document.createElement('div');
                    msgContainer.classList.add("msg");
                    wspace.messeger.data.msgField.appendChild(msgContainer);
                    msgContainer.innerHTML = `<span>${msg['first_name']}</span> ${msg['message']}`;
                });
                
            } else {
                console.log(response.status);
            }
        }
    },
    methods: {
        dialoguesEmptySet: () => {
            if (wspace.messeger.data.dialoguesEmpty) {
                wspace.messeger.data.dialoguesEmpty.addEventListener('click', ()=>{
                    wspace.messeger.data.dialoguesEmpty.style.display = 'none';
                    wspace.messeger.data.viewUsers.style.display = 'flex';
                });
            }
        },
    }
};
document.addEventListener('DOMContentLoaded', ()=>{
    wspace.messeger.methods.dialoguesEmptySet();
    
    wspace.messeger.data.dialogLink.forEach(element => {
        element.addEventListener('click', (e) => {
            wspace.messeger.handler.listenerMsg(element);
        });
    });
        
    /* wspace.messeger.data.sendBtn.addEventListener('click', () => {
        
    }); */
});