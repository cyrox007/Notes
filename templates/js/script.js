document.addEventListener("DOMContentLoaded", function () {
    let overlay = document.querySelector('.overlay');
    setInterval(() => {
        let now = new Date();
        let time = now.getHours() + ':' + now.getMinutes() + ':' + now.getSeconds();
        let date = now.getDate() + '.' + now.getMonth() + '.' + now.getFullYear();
        document.querySelector('.time').innerHTML = time;
        document.querySelector('.date').innerHTML = date;
    },
    1000);
    
    overlay.addEventListener('click', () => {
        overlay.classList.add('active');
        document.querySelector('.login').classList.add('active');
        document.querySelector('.date-time').classList.add('invisible');
    });
});