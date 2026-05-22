// AMD module for the IOMAD Claude chat widget.
import Ajax from 'core/ajax';
import Notification from 'core/notification';

export const init = (blockid) => {
    const root     = document.getElementById(`block-iomad-claude-${blockid}`);
    const textarea = root.querySelector('.claude-question');
    const button   = root.querySelector('.claude-submit');
    const response = root.querySelector('.claude-response');

    const ask = async () => {
        const question = textarea.value.trim();
        if (!question) {
            return;
        }

        button.disabled = true;
        response.textContent = 'Thinking…';
        response.className = 'claude-response mt-3 text-muted';

        try {
            const [answer] = await Ajax.call([{
                methodname: 'block_iomad_claude_ask',
                args: {question},
            }]);

            response.textContent = answer;
            response.className = 'claude-response mt-3';
        } catch (e) {
            response.textContent = '';
            Notification.exception(e);
        } finally {
            button.disabled = false;
        }
    };

    button.addEventListener('click', ask);

    textarea.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            ask();
        }
    });
};
