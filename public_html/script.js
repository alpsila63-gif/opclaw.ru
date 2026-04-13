// ===== HERO CANVAS ANIMATION =====
(function() {
    const canvas = document.getElementById('heroCanvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const hero = document.getElementById('hero');

    let width, height, dpr;
    let mouse = { x: -9999, y: -9999 };
    let particles = [];
    const PARTICLE_COUNT = 80;
    const CONNECTION_DIST = 140;
    const MOUSE_RADIUS = 180;

    const colors = [
        'rgba(14, 165, 233,',  // primary blue
        'rgba(6, 182, 212,',   // cyan
        'rgba(56, 189, 248,',  // light blue
        'rgba(2, 132, 199,',   // dark blue
    ];

    function resize() {
        dpr = Math.min(window.devicePixelRatio || 1, 2);
        width = hero.offsetWidth;
        height = hero.offsetHeight;
        canvas.width = width * dpr;
        canvas.height = height * dpr;
        canvas.style.width = width + 'px';
        canvas.style.height = height + 'px';
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }

    function createParticle() {
        var size = Math.random() * 3 + 1;
        return {
            x: Math.random() * width,
            y: Math.random() * height,
            vx: (Math.random() - 0.5) * 0.4,
            vy: (Math.random() - 0.5) * 0.3 - 0.15, // slight upward drift
            size: size,
            baseSize: size,
            color: colors[Math.floor(Math.random() * colors.length)],
            opacity: Math.random() * 0.5 + 0.2,
            baseOpacity: Math.random() * 0.5 + 0.2,
            phase: Math.random() * Math.PI * 2,
            floatSpeed: Math.random() * 0.008 + 0.003,
            floatAmp: Math.random() * 20 + 10,
        };
    }

    function init() {
        resize();
        particles = [];
        for (var i = 0; i < PARTICLE_COUNT; i++) {
            particles.push(createParticle());
        }
    }

    hero.addEventListener('mousemove', function(e) {
        var rect = hero.getBoundingClientRect();
        mouse.x = e.clientX - rect.left;
        mouse.y = e.clientY - rect.top;
    });
    hero.addEventListener('mouseleave', function() {
        mouse.x = -9999;
        mouse.y = -9999;
    });

    var time = 0;
    function animate() {
        ctx.clearRect(0, 0, width, height);
        time += 0.016;

        // Update & draw particles
        for (var i = 0; i < particles.length; i++) {
            var p = particles[i];

            // Floating motion
            p.x += p.vx + Math.sin(time * 0.5 + p.phase) * 0.15;
            p.y += p.vy + Math.cos(time * 0.3 + p.phase) * 0.1;

            // Anti-gravity drift (slight upward)
            p.y -= 0.05;

            // Mouse repulsion
            var dx = p.x - mouse.x;
            var dy = p.y - mouse.y;
            var dist = Math.sqrt(dx * dx + dy * dy);
            if (dist < MOUSE_RADIUS && dist > 0) {
                var force = (1 - dist / MOUSE_RADIUS) * 2;
                p.x += (dx / dist) * force;
                p.y += (dy / dist) * force;
                p.opacity = Math.min(p.baseOpacity + 0.3, 0.9);
                p.size = p.baseSize * 1.3;
            } else {
                p.opacity += (p.baseOpacity - p.opacity) * 0.05;
                p.size += (p.baseSize - p.size) * 0.05;
            }

            // Wrap around edges
            if (p.x < -20) p.x = width + 20;
            if (p.x > width + 20) p.x = -20;
            if (p.y < -20) p.y = height + 20;
            if (p.y > height + 20) p.y = -20;

            // Draw particle
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
            ctx.fillStyle = p.color + p.opacity + ')';
            ctx.fill();

            // Glow for larger particles
            if (p.baseSize > 2.5) {
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.size * 3, 0, Math.PI * 2);
                var grd = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, p.size * 3);
                grd.addColorStop(0, p.color + (p.opacity * 0.3) + ')');
                grd.addColorStop(1, p.color + '0)');
                ctx.fillStyle = grd;
                ctx.fill();
            }
        }

        // Draw connections
        ctx.lineWidth = 0.5;
        for (var i = 0; i < particles.length; i++) {
            for (var j = i + 1; j < particles.length; j++) {
                var a = particles[i];
                var b = particles[j];
                var dx = a.x - b.x;
                var dy = a.y - b.y;
                var dist = Math.sqrt(dx * dx + dy * dy);
                if (dist < CONNECTION_DIST) {
                    var alpha = (1 - dist / CONNECTION_DIST) * 0.15;
                    ctx.beginPath();
                    ctx.moveTo(a.x, a.y);
                    ctx.lineTo(b.x, b.y);
                    ctx.strokeStyle = 'rgba(14, 165, 233,' + alpha + ')';
                    ctx.stroke();
                }
            }
        }

        requestAnimationFrame(animate);
    }

    function start() {
        init();
        animate();
    }

    if (document.readyState === 'complete') {
        start();
    } else {
        window.addEventListener('load', start);
    }

    var resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            resize();
            for (var i = 0; i < particles.length; i++) {
                if (particles[i].x > width) particles[i].x = Math.random() * width;
                if (particles[i].y > height) particles[i].y = Math.random() * height;
            }
        }, 150);
    });
})();

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
    }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

    fadeEls.forEach(el => observer.observe(el));
} else {
    fadeEls.forEach(el => el.classList.add('visible'));
}

function getScrollOffset() {
    const topBar = document.querySelector('.top-bar');
    const topBarHeight = topBar ? topBar.offsetHeight : 0;
    const navHeight = nav ? nav.offsetHeight : 0;
    return topBarHeight + navHeight + 16;
}

// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
        const href = a.getAttribute('href');
        if (!href || href === '#') return;

        e.preventDefault();
        const targetId = href.slice(1);
        const target = targetId ? document.getElementById(targetId) : null;

        if (target) {
            const targetTop = target.getBoundingClientRect().top + window.scrollY - getScrollOffset();
            window.scrollTo({ top: Math.max(0, targetTop), behavior: 'smooth' });
        }

        closeMobile();
    });
});

// Blog category filter (for blog page)
document.querySelectorAll('.blog-category-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.blog-category-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        const category = btn.dataset.category;
        document.querySelectorAll('.blog-card[data-category]').forEach(card => {
            if (category === 'all' || card.dataset.category === category) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    });
});
