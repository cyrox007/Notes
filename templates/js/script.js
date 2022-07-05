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