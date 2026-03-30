// Navigation scroll effect
const nav = document.getElementById('nav');
window.addEventListener('scroll', () => {
    if (!nav) return;
    nav.classList.toggle('scrolled', window.scrollY > 20);
});

// Mobile menu
const burger = document.getElementById('navBurger');
const mobileMenu = document.getElementById('mobileMenu');

if (burger && mobileMenu) {
    burger.addEventListener('click', () => {
        mobileMenu.classList.toggle('active');
        burger.classList.toggle('active');
    });
}

function closeMobile() {
    if (mobileMenu) mobileMenu.classList.remove('active');
    if (burger) burger.classList.remove('active');
}

// FAQ accordion
function toggleFaq(el) {
    if (!el || !el.parentElement) return;
    const item = el.parentElement;
    const wasActive = item.classList.contains('active');
    document.querySelectorAll('.faq-item').forEach(i => i.classList.remove('active'));
    if (!wasActive) item.classList.add('active');
}

// Scroll animations (Intersection Observer)
const fadeEls = document.querySelectorAll('.fade-in');
if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.15 });

    fadeEls.forEach(el => observer.observe(el));
} else {
    fadeEls.forEach(el => el.classList.add('visible'));
}

function getScrollOffset() {
    const topBar = document.querySelector('.top-bar');
    const topBarHeight = topBar ? topBar.offsetHeight : 0;
    const navHeight = nav ? nav.offsetHeight : 0;
    return topBarHeight + navHeight + 12;
}

// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
        const href = a.getAttribute('href');
        if (!href) return;

        e.preventDefault();

        if (href === '#') {
            window.scrollTo({ top: 0, behavior: 'smooth' });
            closeMobile();
            return;
        }

        const targetId = href.slice(1);
        const target = targetId ? document.getElementById(targetId) : null;

        if (target) {
            const targetTop = target.getBoundingClientRect().top + window.scrollY - getScrollOffset();
            window.scrollTo({ top: Math.max(0, targetTop), behavior: 'smooth' });
        }

        closeMobile();
    });
});
