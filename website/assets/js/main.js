(() => {
  "use strict";

  const body = document.body;
  const menuButton = document.querySelector(".menu-toggle");
  const menu = document.querySelector(".main-menu");
  const glow = document.querySelector(".cursor-glow");

  if (menuButton && menu) {
    const closeMenu = () => {
      menu.classList.remove("is-open");
      menuButton.classList.remove("is-open");
      menuButton.setAttribute("aria-expanded", "false");
      body.classList.remove("menu-open");
    };

    menuButton.addEventListener("click", () => {
      const open = !menu.classList.contains("is-open");
      menu.classList.toggle("is-open", open);
      menuButton.classList.toggle("is-open", open);
      menuButton.setAttribute("aria-expanded", String(open));
      body.classList.toggle("menu-open", open);
    });

    menu.querySelectorAll("a").forEach(link => link.addEventListener("click", closeMenu));
    window.addEventListener("resize", () => {
      if (window.innerWidth > 850) closeMenu();
    });
    document.addEventListener("keydown", event => {
      if (event.key === "Escape") closeMenu();
    });
  }

  const revealItems = document.querySelectorAll(".reveal");
  if ("IntersectionObserver" in window) {
    const observer = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add("is-visible");
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: "0px 0px -40px" });

    revealItems.forEach((item, index) => {
      item.style.transitionDelay = `${Math.min(index % 4, 3) * 70}ms`;
      observer.observe(item);
    });
  } else {
    revealItems.forEach(item => item.classList.add("is-visible"));
  }

  if (glow && window.matchMedia("(pointer:fine)").matches) {
    window.addEventListener("pointermove", event => {
      glow.style.left = `${event.clientX}px`;
      glow.style.top = `${event.clientY}px`;
    }, { passive: true });
  }

  const stage = document.querySelector(".hero-stage");
  const orb = document.querySelector(".logo-orb");
  if (stage && orb && window.matchMedia("(pointer:fine)").matches) {
    stage.addEventListener("pointermove", event => {
      const box = stage.getBoundingClientRect();
      const x = (event.clientX - box.left) / box.width - 0.5;
      const y = (event.clientY - box.top) / box.height - 0.5;
      orb.style.transform = `translate3d(${x * 10}px, ${y * 10}px, 0) rotateX(${-y * 3}deg) rotateY(${x * 3}deg)`;
    });
    stage.addEventListener("pointerleave", () => {
      orb.style.transform = "";
    });
  }

  const year = document.getElementById("year");
  if (year) year.textContent = new Date().getFullYear();
})();
