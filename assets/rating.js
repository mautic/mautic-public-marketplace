function initRating() {
    const form = document.getElementById('review-form');
    const starsContainer = document.getElementById('rating-stars');
    const ratingInput = document.getElementById('rating');

    if (!form || !starsContainer || !ratingInput) {
        return;
    }

    const stars = starsContainer.querySelectorAll('button[data-rating]');

    function setRating(nextRating) {
        ratingInput.value = String(nextRating);
        updateStars(nextRating);
        ratingInput.dispatchEvent(new Event('input', { bubbles: true }));
        ratingInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    stars.forEach(function (star) {
        star.addEventListener('click', function () {
            setRating(this.getAttribute('data-rating') || '0');
        });

        star.addEventListener('mouseenter', function () {
            highlightStars(this.getAttribute('data-rating'));
        });

        star.addEventListener('mouseleave', function () {
            highlightStars(ratingInput.value);
        });

        star.addEventListener('keydown', function (event) {
            const currentIndex = Number.parseInt(this.getAttribute('data-rating'), 10) - 1;

            if (['ArrowLeft', 'ArrowDown'].includes(event.key)) {
                event.preventDefault();
                stars[Math.max(0, currentIndex - 1)]?.focus();
                setRating(Math.max(1, currentIndex));
            }

            if (['ArrowRight', 'ArrowUp'].includes(event.key)) {
                event.preventDefault();
                stars[Math.min(stars.length - 1, currentIndex + 1)]?.focus();
                setRating(Math.min(stars.length, currentIndex + 2));
            }

            if ('Home' === event.key) {
                event.preventDefault();
                stars[0]?.focus();
                setRating(1);
            }

            if ('End' === event.key) {
                event.preventDefault();
                stars[stars.length - 1]?.focus();
                setRating(stars.length);
            }

            if (['Enter', ' '].includes(event.key)) {
                event.preventDefault();
                setRating(this.getAttribute('data-rating') || '0');
            }
        });
    });

    function updateStars(rating) {
        const normalizedRating = Number.parseInt(String(rating), 10) || 0;

        stars.forEach(function (s, index) {
            const isActive = index < normalizedRating;
            const isSelected = index + 1 === normalizedRating;

            s.setAttribute('aria-checked', isSelected ? 'true' : 'false');
            s.setAttribute('tabindex', index === Math.max(0, normalizedRating - 1) ? '0' : (0 === normalizedRating && 0 === index ? '0' : '-1'));

            if (isActive) {
                s.classList.add('review-form__star-button--active');
            } else {
                s.classList.remove('review-form__star-button--active');
            }
        });
    }

    function highlightStars(rating) {
        const n = Number.parseInt(String(rating), 10) || 0;

        stars.forEach(function (s, index) {
            if (index < n) {
                s.classList.add('review-form__star-button--hover');
            } else {
                s.classList.remove('review-form__star-button--hover');
            }
        });
    }

    updateStars(ratingInput.value);
}

document.addEventListener('turbo:load', initRating);
