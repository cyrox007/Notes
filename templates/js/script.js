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

(function () {
    let id = 3;
    let token = 123456;
    let socket = new WebSocket(`ws://localhost:27800?user_id=${id}&user_token=${token}`);
    socket.onopen = (event)=>{
        console.log("connect");
    };
    socket.onmessage = (event) => {
        let server_data = JSON.parse(event.data);
        console.log(server_data.action);
        if (server_data["action"] == "Ping") {
            let data = JSON.stringify({"action": "Pong"});
            socket.send(data);
        } else {
            
        }
    };
}());