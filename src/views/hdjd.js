// Function to check if the active element is within an iframe
function checkIframeClick() {
    const interval = setInterval(() => {
        const activeElement = document.activeElement;
        if (activeElement.tagName === "IFRAME") {
            // If the active element is the Calendly iframe
            console.log("IFRAME clicked");
            // Here you can perform additional checks or actions based on clicks within the iframe
        }
    }, 1000); // Adjust the interval based on your needs (e.g., every second)

    // Clear the interval after a certain duration or when needed
    setTimeout(() => {
        clearInterval(interval);
    }, 60000); // Stop after 60 seconds (adjust as needed)
}

// Call the function after a delay to ensure the iframe has loaded
setTimeout(checkIframeClick, 2000); // Adjust the delay based on iframe loading time
