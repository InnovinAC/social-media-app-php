import './helpers/body'

// alert ("hey")

document.addEventListener('DOMContentLoaded', () => {
    const navElems = document.querySelectorAll('.mobile-nav-link');
    if (navElems) {
        navElems.forEach((navElem) => {
            const href = navElem?.querySelector('a')?.href;
            if (location.href == href) {
                console.log("not equal")
                navElem.style.borderBottom = "2px solid white";
            }
        })
    }
})