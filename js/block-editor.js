const { createElement, useState } = wp.element
const { registerPlugin } = wp.plugins
const { PluginPostStatusInfo } = wp.editor

const DeeplTranslationButton = () => {
    const [isLoading, setIsLoading] = useState(false)
    const [label, setLabel] = useState('Traduction automatique Deepl')

    const send = async () => {
        if (!confirm("La traduction va écraser le contenu actuel. Continuer ?")) return

        setIsLoading(true)
        setLabel('Traduction en cours...')
        await new Promise(r => setTimeout(r, 1000))

        let response
        try {
            response = await wp.ajax.post('polylang_deepl_translate_post', polylang_deepl);
            setLabel('Traduction terminée !')
            document.location.replace(document.location.href)
        } catch (error) {
            setLabel('Traduction en erreur: ' + error)
            setIsLoading(false)
            return
        }

    }

    return createElement(
        PluginPostStatusInfo,
        null,
        createElement('button',
            {
                className: 'components-button is-next-40px-default-size is-tertiary' + (isLoading ? ' disabled' : ''),
                disabled: isLoading,
                style: { width: '100%', height: 'auto', justifyContent: 'center', whiteSpace: 'wrap' },
                onClick: send,
                children: label,
            },
        )
    )
}

registerPlugin('wp-polylang-deepl', {
    render: DeeplTranslationButton,
})
