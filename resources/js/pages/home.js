import lottie from 'lottie-web';

const initLottie = () => {
  const container = document.getElementById('lottie-hero');
  if (!container) return;

  const src = container.dataset.lottieSrc;
  if (!src) {
    return;
  }

  fetch(src)
    .then((response) => {
      if (!response.ok) {
        throw new Error(`Failed to load Lottie: ${response.status}`);
      }
      return response.json();
    })
    .then((animationData) => {
      lottie.loadAnimation({
        container,
        renderer: 'svg',
        loop: true,
        autoplay: true,
        animationData,
        rendererSettings: {
          preserveAspectRatio: 'xMidYMid meet',
        },
      });
    })
    .catch((error) => {
      console.error('Lottie init failed', error);
    });
};

if (document.readyState === 'loading') {
  window.addEventListener('DOMContentLoaded', initLottie, { once: true });
} else {
  initLottie();
}
