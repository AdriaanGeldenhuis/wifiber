(() => {
  // ----- Hero slider -----
  const slider = document.querySelector('.hero-slider');
  if (slider) {
    const slides   = [...slider.querySelectorAll('.slide')];
    const dots     = [...slider.querySelectorAll('.slider-dot')];
    const prevBtn  = slider.querySelector('.slider-prev');
    const nextBtn  = slider.querySelector('.slider-next');
    const SLIDE_MS = 6000;
    let current    = 0;
    let timer      = null;
    let paused     = false;

    slider.style.setProperty('--slide-duration', SLIDE_MS + 'ms');

    const go = (idx) => {
      idx = (idx + slides.length) % slides.length;
      slides[current]?.classList.remove('is-active');
      dots[current]?.classList.remove('is-active');
      dots[current]?.setAttribute('aria-selected', 'false');
      current = idx;
      slides[current].classList.add('is-active');
      dots[current]?.classList.add('is-active');
      dots[current]?.setAttribute('aria-selected', 'true');
      restart();
    };

    const next = () => go(current + 1);
    const prev = () => go(current - 1);

    const restart = () => {
      clearInterval(timer);
      if (!paused && slides.length > 1) timer = setInterval(next, SLIDE_MS);
    };

    nextBtn?.addEventListener('click', next);
    prevBtn?.addEventListener('click', prev);
    dots.forEach(d => d.addEventListener('click', () => go(parseInt(d.dataset.go, 10))));

    slider.addEventListener('mouseenter', () => { paused = true;  clearInterval(timer); });
    slider.addEventListener('mouseleave', () => { paused = false; restart(); });

    // Pause when tab is not visible
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) { clearInterval(timer); }
      else if (!paused)    { restart(); }
    });

    // Touch swipe
    let touchStartX = 0;
    slider.addEventListener('touchstart', e => { touchStartX = e.changedTouches[0].screenX; }, { passive: true });
    slider.addEventListener('touchend',   e => {
      const dx = e.changedTouches[0].screenX - touchStartX;
      if (Math.abs(dx) > 40) (dx < 0 ? next : prev)();
    }, { passive: true });

    // Keyboard
    slider.tabIndex = 0;
    slider.addEventListener('keydown', e => {
      if (e.key === 'ArrowRight') next();
      if (e.key === 'ArrowLeft')  prev();
    });

    restart();
  }

  // ----- Mobile nav toggle -----
  const toggle = document.querySelector('.nav-toggle');
  const nav    = document.getElementById('main-nav');
  if (toggle && nav) {
    toggle.addEventListener('click', () => {
      const open = nav.classList.toggle('open');
      toggle.setAttribute('aria-expanded', String(open));
    });
    nav.querySelectorAll('a').forEach(a => a.addEventListener('click', () => {
      nav.classList.remove('open');
      toggle.setAttribute('aria-expanded', 'false');
    }));
  }

  // ----- Pricing tier switcher -----
  const tierBtns   = document.querySelectorAll('.tier-btn');
  const tierPanels = document.querySelectorAll('.tier-panel');
  tierBtns.forEach(btn => btn.addEventListener('click', () => {
    const target = btn.dataset.tier;
    tierBtns.forEach(b => {
      const on = b === btn;
      b.classList.toggle('active', on);
      b.setAttribute('aria-selected', String(on));
    });
    tierPanels.forEach(p => {
      p.classList.toggle('active', p.dataset.panel === target);
    });
  }));

  // ----- Legal section switcher -----
  const legalBtns   = document.querySelectorAll('.legal-nav button');
  const legalPanels = document.querySelectorAll('.legal-panel');
  legalBtns.forEach(btn => btn.addEventListener('click', () => {
    const target = btn.dataset.legal;
    legalBtns.forEach(b => b.classList.toggle('active', b === btn));
    legalPanels.forEach(p => p.classList.toggle('active', p.dataset.legalPanel === target));
    // Scroll into view on small screens
    if (window.innerWidth < 800) {
      document.querySelector('.legal-content')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }));

  // ----- Contact form: client-side feedback -----
  const form  = document.getElementById('contactForm');
  const alert = document.getElementById('formAlert');
  if (form && alert) {
    form.addEventListener('submit', async (e) => {
      // Let the browser validate first
      if (!form.checkValidity()) return;

      e.preventDefault();
      alert.innerHTML = '';
      const btn = form.querySelector('button[type="submit"]');
      const originalText = btn.textContent;
      btn.disabled = true;
      btn.textContent = 'Sending...';

      try {
        const res = await fetch(form.action, {
          method: 'POST',
          body: new FormData(form),
          headers: { 'X-Requested-With': 'fetch' },
          redirect: 'follow',
        });
        if (res.ok) {
          alert.innerHTML = '<div class="alert alert-success">Thanks &mdash; we\'ve got your message and will be in touch shortly.</div>';
          form.reset();
        } else {
          throw new Error('bad response');
        }
      } catch {
        alert.innerHTML = '<div class="alert alert-error">Couldn\'t send right now. Please email admin@wifiber.co.za or call us directly.</div>';
      } finally {
        btn.disabled = false;
        btn.textContent = originalText;
      }
    });
  }

  // ---------- App-store coming-soon toast ----------
  // Until the published App Store / Google Play URLs are wired into the
  // <a> elements, intercept clicks on .is-coming-soon buttons and show
  // a small toast instead of navigating to "#".
  (function () {
    var triggers = document.querySelectorAll('.store-btn.is-coming-soon');
    if (!triggers.length) return;

    var toast;
    var hideTimer;
    function showToast(label) {
      if (!toast) {
        toast = document.createElement('div');
        toast.className = 'app-toast';
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        document.body.appendChild(toast);
      }
      toast.textContent = label + ' link goes live the day we publish — thanks for the interest!';
      // Force reflow so the transition fires every time.
      void toast.offsetWidth;
      toast.classList.add('is-visible');
      clearTimeout(hideTimer);
      hideTimer = setTimeout(function () { toast.classList.remove('is-visible'); }, 3200);
    }

    triggers.forEach(function (a) {
      a.addEventListener('click', function (ev) {
        // If a real URL has been wired in, let the browser handle it.
        var href = a.getAttribute('href') || '';
        if (href && href !== '#' && href.charAt(0) !== '#') return;
        ev.preventDefault();
        showToast(a.dataset.comingSoon || 'App store');
      });
    });
  })();
})();
