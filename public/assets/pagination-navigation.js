(() => {
    'use strict';

    if (window.CATDashboardPagination) return;

    let navigationInProgress = false;

    const copyBodyAttributes = (source, target) => {
        Array.from(target.attributes).forEach((attribute) => {
            target.removeAttribute(attribute.name);
        });
        Array.from(source.attributes).forEach((attribute) => {
            target.setAttribute(attribute.name, attribute.value);
        });
    };

    const copyClassState = (nextDocument, selector, className, keyForElement) => {
        const states = new Map();
        document.querySelectorAll(selector).forEach((element) => {
            const key = keyForElement(element);
            if (!states.has(key)) {
                states.set(key, element.classList.contains(className));
            }
        });

        nextDocument.querySelectorAll(selector).forEach((element) => {
            const key = keyForElement(element);
            if (states.has(key)) {
                element.classList.toggle(className, states.get(key));
            }
        });
    };

    const preserveRosterDisplayState = (nextDocument) => {
        copyClassState(
            nextDocument,
            '[data-optional-column]',
            'is-settings-hidden',
            (element) => element.dataset.optionalColumn
        );
        copyClassState(
            nextDocument,
            '[data-stage-detail][data-ribbon-skill]',
            'hidden-column',
            (element) => `${element.dataset.stageDetail}:${element.dataset.ribbonSkill}`
        );
    };

    const executeDashboardApplication = (nextDocument) => {
        const applicationScript = Array.from(nextDocument.scripts).find((script) => (
            new URL(script.src, window.location.href).pathname.endsWith('/assets/app.js')
        ));
        if (!applicationScript) return;

        const script = document.createElement('script');
        script.src = applicationScript.src;
        document.head.append(script);
        script.addEventListener('load', () => script.remove(), { once: true });
    };

    const navigate = async (destination, historyMode = 'push') => {
        if (navigationInProgress) return;
        navigationInProgress = true;

        const destinationUrl = new URL(destination, window.location.href);
        const scrollX = window.scrollX;
        const scrollY = window.scrollY;
        document.body.setAttribute('aria-busy', 'true');

        try {
            const response = await window.fetch(destinationUrl.toString(), {
                credentials: 'same-origin',
                headers: {
                    Accept: 'text/html',
                    'X-Requested-With': 'CAT-Pagination',
                },
            });
            if (!response.ok) {
                throw new Error(`Dashboard request failed with status ${response.status}.`);
            }

            const nextDocument = new DOMParser().parseFromString(
                await response.text(),
                'text/html'
            );
            const nextBody = nextDocument.body;
            if (!nextBody || !nextDocument.querySelector('[data-page-size]')) {
                throw new Error('The dashboard response was incomplete.');
            }

            preserveRosterDisplayState(nextDocument);

            const nextChildren = Array.from(nextBody.childNodes).map((node) => (
                document.importNode(node, true)
            ));

            copyBodyAttributes(nextBody, document.body);
            document.body.replaceChildren(...nextChildren);
            document.title = nextDocument.title;

            if (historyMode === 'push') {
                window.history.pushState({}, '', destinationUrl);
            } else if (historyMode === 'replace') {
                window.history.replaceState({}, '', destinationUrl);
            }

            // Force layout and restore the unchanged viewport before the browser
            // has an opportunity to paint the newly inserted dashboard.
            void document.body.offsetHeight;
            window.scrollTo(scrollX, scrollY);

            executeDashboardApplication(nextDocument);
        } catch (error) {
            if (historyMode === 'none') {
                window.location.reload();
            } else {
                window.location.assign(destinationUrl.toString());
            }
        } finally {
            navigationInProgress = false;
            document.body.removeAttribute('aria-busy');
        }
    };

    window.CATDashboardPagination = { navigate };

    document.addEventListener('click', (event) => {
        if (
            event.defaultPrevented
            || event.button !== 0
            || event.metaKey
            || event.ctrlKey
            || event.shiftKey
            || event.altKey
        ) {
            return;
        }

        const link = event.target.closest?.('[data-pagination-link]');
        if (!link) return;

        event.preventDefault();
        navigate(link.href);
    });

    window.addEventListener('popstate', () => {
        navigate(window.location.href, 'none');
    });
})();
