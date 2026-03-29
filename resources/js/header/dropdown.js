const menu = document.querySelector('.menu');
const trigger = document.querySelector('.menu-trigger');

trigger?.addEventListener('click', (e) => {
  e.preventDefault(); // stop link navigation
  const open = menu.classList.toggle('is-open');
  trigger.setAttribute('aria-expanded', String(open));
});

window.addEventListener('click', (e) => {
  if (!menu.contains(e.target)) {
    menu.classList.remove('is-open');
    trigger.setAttribute('aria-expanded', 'false');
  }
});
