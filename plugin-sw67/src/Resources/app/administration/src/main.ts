import './module/sw-order';
import './init/invoice-service.init';
import './init/credit_note-service.init';

function initializeMonduValidation() {
    const apiTokenField = document.querySelector('input[name*="apiToken"]');
    if (!apiTokenField) {
        return false;
    }

    const fieldContainer = apiTokenField.closest('.sw-field');
    if (!fieldContainer) {
        return false;
    }

    if (fieldContainer.querySelector('.mondu-validate-button')) {
        return true;
    }

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'mondu-validate-button';
    button.textContent = 'Validate API Credentials';

    button.style.cssText = `
        display: flex !important;
        align-items: center !important;
        line-height: 16px !important;
        font-size: 14px !important;
        margin: 25px 0px 0px 0px !important;
        color: var(--color-text-primary-default, #52667a);
        background: none !important;
        border: none !important;
        font-family: inherit !important;
        padding: 0px !important;
        cursor: pointer !important;
        text-decoration: underline !important;
        text-align: left !important;
        float: left !important;
    `;

    if (fieldContainer) {
        fieldContainer.style.marginBottom = '10px';
    }

    button.addEventListener('click', async function(e) {
        e.preventDefault();
        e.stopPropagation();

        if (button.disabled) {
            return;
        }
        
        const tokenInput = document.querySelector('input[name*="apiToken"]') as HTMLInputElement;
        if (!tokenInput || !tokenInput.value.trim()) {
            alert('Please enter API token first');
            return;
        }

        const sandboxModeInput = document.querySelector('input[name*="sandboxMode"]') as HTMLInputElement;
        const isSandboxMode = sandboxModeInput ? sandboxModeInput.checked : true;

        button.textContent = 'Validating...';
        button.disabled = true;

        try {
            const response = await fetch('/mondu/config/test', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    apiCredentials: tokenInput.value.trim(),
                    sandboxMode: isSandboxMode
                })
            });

            const result = await response.json();
            
            if (result.error === '0') {
                alert('API credentials are valid!');
            } else {
                alert('API credentials are invalid: ' + (result.message || 'Unknown error'));
            }
        } catch (error) {
            alert('Connection error. Please check the server logs.');
        } finally {
            button.textContent = 'Validate API Credentials';
            button.disabled = false;
        }
    });

    fieldContainer.appendChild(button);
    return true;
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        setTimeout(initializeMonduValidation, 1000);
    });
} else {
    setTimeout(initializeMonduValidation, 1000);
}

const observer = new MutationObserver(function(mutations) {
    let shouldReinitialize = false;
    
    mutations.forEach(function(mutation) {
        if (mutation.type === 'childList') {
            mutation.addedNodes.forEach(function(node) {
                if (node.nodeType === 1) {
                    const element = node as Element;
                    if (element.querySelector && element.querySelector('input[name*="apiToken"]')) {
                        shouldReinitialize = true;
                    }
                }
            });
        }
    });
    
    if (shouldReinitialize) {
        setTimeout(initializeMonduValidation, 500);
    }
});

observer.observe(document.body, {
    childList: true,
    subtree: true
});