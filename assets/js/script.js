{literal}
document.addEventListener("DOMContentLoaded", function () {
    let sidebarControl = document.getElementById('sidebarControl');
    let sidebar = document.querySelector('.sidebar');
    let content = document.querySelector('.wrapper__content');

    sidebarControl.addEventListener('click', (e)=>{
        e.preventDefault();
        content.classList.toggle('sidebar--active');
        sidebar.classList.toggle('active');
    });
});
wspace.core = {
    data: {
        socket: new WebSocket(`ws://localhost:27800?user_id={/literal}{$user['id']}{literal}`),
        messagesArray: null, // Здесь будут храниться сообщения, которые придут от WS сервера
        userID: null,
    }
};
(function () {
    wspace.core.data.socket.onopen = (event)=>{
        /* console.log("connect"); */
    };
    wspace.core.data.socket.onmessage = (event) => {
        let server_data = JSON.parse(event.data);
        console.log(server_data);
        if (server_data["action"] == "Ping") {
            let data = JSON.stringify({"action": "Pong"});
            wspace.core.data.socket.send(data); 
        } else {
            wspace.core.data.userID = server_data["userId"];
            wspace.core.data.messagesArray = server_data["userDialoges"];
            wspace.messeger.methods.loadMessages();
        }
    };
}());
{/literal}