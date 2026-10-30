(() => {
    async function printReport() {
        if (document.readyState !== "complete") {
            await new Promise((resolve) =>
                window.addEventListener("load", resolve, { once: true }),
            );
        }

        await document.fonts?.ready;
        await Promise.allSettled(
            Array.from(document.images, (image) => image.decode?.()),
        );
        await new Promise((resolve) =>
            window.requestAnimationFrame(() =>
                window.requestAnimationFrame(resolve),
            ),
        );

        window.print();
    }

    void printReport();
})();
