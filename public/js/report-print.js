(() => {
    let printed = false;
    window.addEventListener(
        "beforeprint",
        () => {
            printed = true;
        },
        { once: true },
    );

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
        if (!printed) {
            window.print();
        }
    }

    void printReport();
})();
