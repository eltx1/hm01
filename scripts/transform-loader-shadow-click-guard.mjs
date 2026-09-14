const MARKER = 'function composedClickGuardParent(node)';

function replaceOnce(source, search, replacement, label) {
    const next = source.replace(search, replacement);
    if (next === source) {
        throw new Error(`Shadow Click Guard transform anchor missing: ${label}`);
    }
    return next;
}

const shadowAwareContainment = String.raw`    function composedClickGuardParent(node) {
        if (!node) return null;
        if (node.parentNode) return node.parentNode;
        if (typeof node.getRootNode === 'function') {
            try {
                var root = node.getRootNode();
                if (root && root.host && root.host !== node) return root.host;
            } catch (error) {
                return null;
            }
        }
        return null;
    }

    function containerContains(container, node) {
        if (!container || !node) return false;
        if (typeof container.contains === 'function' && container.contains(node)) return true;
        var current = node;
        var visited = [];
        while (current) {
            if (current === container) return true;
            if (visited.indexOf(current) !== -1) return false;
            visited.push(current);
            current = composedClickGuardParent(current);
        }
        return false;
    }
`;

const shadowAwareIframeDiscovery = String.raw`    function iframeNodes(node) {
        var frames = [];
        var visited = [];
        function visit(current) {
            if (!current || visited.indexOf(current) !== -1) return;
            visited.push(current);
            if (isIframe(current) && frames.indexOf(current) === -1) frames.push(current);

            // Quick GPT intentionally mounts its provider frame in an open
            // ShadowRoot. Traverse only roots that are explicitly exposed by
            // the managed placement so Click Guard stays placement-scoped.
            if (current.shadowRoot) visit(current.shadowRoot);

            var children = current.children || current.childNodes || [];
            Array.prototype.forEach.call(children, function (child) { visit(child); });
        }
        visit(node);
        return frames;
    }
`;

export function applyShadowClickGuardTransform(input) {
    let source = String(input || '');
    if (source.includes(MARKER)) return source;

    source = replaceOnce(
        source,
        /    function containerContains\(container, node\) \{[\s\S]*?\n    \}\n\n    function isEligibleClickGuardIframe/,
        shadowAwareContainment + '\n    function isEligibleClickGuardIframe',
        'composed containment',
    );

    source = replaceOnce(
        source,
        /    function iframeNodes\(node\) \{[\s\S]*?\n    \}\n\n    function trackClickGuardIframe/,
        shadowAwareIframeDiscovery + '\n    function trackClickGuardIframe',
        'shadow iframe discovery',
    );

    return source;
}
