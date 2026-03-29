const body = document.body;
const setHeaderCompact = () => {
    if (window.scrollY > 12) {
        body.classList.add('header-compact');
        return;
    }
    body.classList.remove('header-compact');
};

setHeaderCompact();
window.addEventListener('scroll', setHeaderCompact, { passive: true });