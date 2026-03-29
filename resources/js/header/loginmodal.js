const modal = document.getElementById('login-modal');
const openButton = document.getElementById('open-login');
const closeButton = document.getElementById('close-login');
const cancelButton = document.getElementById('cancel-login');

openButton?.addEventListener('click', () => {
    modal.style.display = 'block';
});

closeButton?.addEventListener('click', () => {
    modal.style.display = 'none';
});

cancelButton?.addEventListener('click', () => {
    modal.style.display = 'none';
});

window.addEventListener('click', (event) => {
    if (event.target === modal) {
        modal.style.display = 'none';
    }
});


