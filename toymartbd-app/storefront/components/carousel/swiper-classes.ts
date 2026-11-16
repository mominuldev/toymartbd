// Class names shared by SwiperCarousel (a client module) and the Server Components that render its
// slides. Kept out of the 'use client' file: a value exported from there reaches a Server Component
// as a client reference, not as the string, so the slides would never be found by Swiper.

export const swiperTrackClass = "tm-swiper-track";

/** Put this class on every slide (`<li>`). */
export const swiperSlideClass = "tm-swiper-slide";
