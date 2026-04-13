function initRating() {
    const form = document.getElementById('review-form');
    const starsContainer = document.getElementById('rating-stars');
    const ratingInput = document.getElementById('rating');

    if (!form || !starsContainer || !ratingInput) {
        return;
    }

    const stars = starsContainer.querySelectorAll('.star-icon');

    stars.forEach(function (star) {
        star.addEventListener('click', function () {
            const rating = this.getAttribute('data-rating') || '0';
            ratingInput.value = rating;
            updateStars(rating);
        });

        star.addEventListener('mouseenter', function () {
            const rating = this.getAttribute('data-rating');
            highlightStars(rating);
        });

        star.addEventListener('mouseleave', function () {
            highlightStars(ratingInput.value);
        });
    });

    function updateStars(rating) {
        stars.forEach(function (s, index) {
            if (index < Number(rating)) {
                s.classList.add('star-active');
            } else {
                s.classList.remove('star-active');
            }
        });
    }

    function highlightStars(rating) {
        stars.forEach(function (s, index) {
            if (index < Number(rating)) {
                s.classList.add('star-hover');
            } else {
                s.classList.remove('star-hover');
            }
        });
    }

    updateStars(ratingInput.value);
}

document.addEventListener('turbo:load', initRating);
