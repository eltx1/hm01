import './bootstrap';

const navigation = document.querySelector('#control-navigation');
const navigationToggle = document.querySelector('[data-nav-toggle]');
const navigationScrim = document.querySelector('[data-nav-close]');

const setNavigation = (open) => {
    if (!navigation || !navigationToggle || !navigationScrim) return;

    navigation.classList.toggle('is-open', open);
    navigationScrim.classList.toggle('is-open', open);
    navigationToggle.setAttribute('aria-expanded', String(open));
    document.body.classList.toggle('navigation-open', open);
};

navigationToggle?.addEventListener('click', () => setNavigation(!navigation?.classList.contains('is-open')));
navigationScrim?.addEventListener('click', () => setNavigation(false));
navigation?.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => setNavigation(false)));
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') setNavigation(false);
});
