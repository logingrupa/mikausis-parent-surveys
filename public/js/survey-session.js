(() => {
    "use strict";

    const controllers = new WeakMap();
    const maxAge = 4 * 60 * 1000;
    const uuid =
        /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iu;

    function create(
        form,
        { fetch: transport = globalThis.fetch, now = Date.now } = {},
    ) {
        const endpoint = new URL(
            form.dataset.sessionUrl,
            globalThis.location.href,
        );
        if (endpoint.origin !== globalThis.location.origin)
            throw new TypeError("Invalid session endpoint");
        let refreshedAt = null;
        let pending = null;
        let redirect = null;

        async function refresh(force = false) {
            if (pending) return pending;
            if (!force && refreshedAt !== null && now() - refreshedAt < maxAge)
                return;
            pending = (async () => {
                const proof = form.dataset.sessionResume;
                const response = await transport(endpoint.href, {
                    method: "GET",
                    credentials: "same-origin",
                    mode: "same-origin",
                    cache: "no-store",
                    redirect: "error",
                    headers: {
                        Accept: "application/json",
                        "X-Requested-With": "XMLHttpRequest",
                        ...(proof ? { "X-Survey-Resume": proof } : {}),
                    },
                });
                if (!response.ok || response.redirected)
                    throw new TypeError("Session renewal failed");
                const result = await response.json();
                if (
                    !result ||
                    typeof result.csrf_token !== "string" ||
                    !/^[a-z0-9]{40,128}$/iu.test(result.csrf_token) ||
                    (proof && !uuid.test(result.submission_id || ""))
                )
                    throw new TypeError("Invalid session response");
                form.querySelector('[name="_token"]').value = result.csrf_token;
                const submission = form.querySelector('[name="submission_id"]');
                if (proof && submission)
                    submission.value = result.submission_id;
                if (
                    proof &&
                    result.submitted === true &&
                    typeof result.redirect === "string"
                ) {
                    const completed = new URL(result.redirect, endpoint.href);
                    if (completed.origin !== endpoint.origin)
                        throw new TypeError("Invalid completion URL");
                    redirect = completed.href;
                }
                refreshedAt = now();
            })();
            try {
                await pending;
            } finally {
                pending = null;
            }
        }

        async function request(url, options) {
            const target = new URL(url, globalThis.location.href);
            if (target.origin !== endpoint.origin)
                throw new TypeError("Invalid submission endpoint");
            const mainRequest = Boolean(
                form.querySelector('[name="submission_id"]') &&
                    target.href ===
                        new URL(form.action, globalThis.location.href).href,
            );
            await refresh();
            if (redirect && mainRequest)
                return {
                    ok: true,
                    status: 200,
                    json: async () => ({ redirect }),
                };
            const send = () => {
                const csrf = form.querySelector('[name="_token"]').value;
                const body = options.body;
                if (body && typeof body.set === "function") {
                    body.set("_token", csrf);
                    const submission = form.querySelector(
                        '[name="submission_id"]',
                    );
                    if (submission && body.has("submission_id"))
                        body.set("submission_id", submission.value);
                }
                return transport(target.href, {
                    ...options,
                    credentials: "same-origin",
                    mode: "same-origin",
                    redirect: "error",
                    headers: { ...options.headers, "X-CSRF-TOKEN": csrf },
                });
            };
            const response = await send();
            let staleBinding = false;
            if (
                response.status === 422 &&
                mainRequest &&
                form.dataset.sessionResume &&
                response.clone
            ) {
                try {
                    const result = await response.clone().json();
                    staleBinding = Object.hasOwn(
                        result.errors || {},
                        "submission_id",
                    );
                } catch {}
            }
            if (response.status !== 419 && !staleBinding) return response;
            await refresh(true);
            if (redirect && mainRequest)
                return {
                    ok: true,
                    status: 200,
                    json: async () => ({ redirect }),
                };
            return send();
        }

        return Object.freeze({
            refresh,
            request,
            get redirect() {
                return redirect;
            },
        });
    }

    function forForm(form) {
        if (!form.dataset.sessionUrl) return null;
        if (!controllers.has(form)) controllers.set(form, create(form));
        return controllers.get(form);
    }

    globalThis.MikausisSurveySession = Object.freeze({ create, forForm });
})();
