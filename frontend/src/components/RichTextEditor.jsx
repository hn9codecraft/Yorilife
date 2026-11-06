import { useEffect, useRef } from 'react'

const TOOLS = [
  { command: 'bold', icon: 'bi-type-bold', label: 'Bold' },
  { command: 'italic', icon: 'bi-type-italic', label: 'Italic' },
  { command: 'underline', icon: 'bi-type-underline', label: 'Underline' },
  { command: 'formatBlock', value: 'h3', icon: 'bi-type-h3', label: 'Heading' },
  { command: 'insertUnorderedList', icon: 'bi-list-ul', label: 'Bulleted list' },
  { command: 'insertOrderedList', icon: 'bi-list-ol', label: 'Numbered list' },
  { command: 'formatBlock', value: 'blockquote', icon: 'bi-quote', label: 'Quote' },
  { command: 'createLink', icon: 'bi-link-45deg', label: 'Add link' },
  { command: 'removeFormat', icon: 'bi-eraser', label: 'Clear formatting' },
]

/**
 * Small formatted-text editor (bold, italic, underline, heading, lists, quote,
 * links). The HTML is cleaned again on the server, so only those tags are kept.
 * `resetKey` reloads `value` into the editor (e.g. after a template is applied).
 */
export default function RichTextEditor({ id, value, onChange, resetKey, invalid = false, describedBy, labelledBy }) {
  const ref = useRef(null)

  useEffect(() => {
    if (ref.current) ref.current.innerHTML = value ?? ''
    // Only on mount / explicit reset: typing must not move the caret.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [resetKey])

  const run = (tool) => {
    ref.current?.focus()
    if (tool.command === 'createLink') {
      const url = window.prompt('Link address (https://...)', 'https://')
      if (!url || !/^(https?:\/\/|mailto:)/i.test(url.trim())) return
      document.execCommand('createLink', false, url.trim())
    } else {
      document.execCommand(tool.command, false, tool.value ?? null)
    }
    onChange(ref.current?.innerHTML ?? '')
  }

  return (
    <div className={`rich-editor${invalid ? ' is-invalid' : ''}`}>
      <div className="rich-editor__toolbar" role="toolbar" aria-label="Text formatting">
        {TOOLS.map((tool) => (
          <button
            key={tool.label}
            type="button"
            className="btn btn-sm btn-outline-secondary btn-icon"
            title={tool.label}
            aria-label={tool.label}
            onMouseDown={(event) => event.preventDefault()}
            onClick={() => run(tool)}
          >
            <i className={`bi ${tool.icon}`} aria-hidden="true" />
          </button>
        ))}
      </div>
      <div
        id={id}
        ref={ref}
        className="rich-editor__area announcement-body"
        contentEditable
        role="textbox"
        aria-multiline="true"
        aria-labelledby={labelledBy}
        aria-describedby={describedBy}
        aria-invalid={invalid || undefined}
        tabIndex={0}
        suppressContentEditableWarning
        onInput={(event) => onChange(event.currentTarget.innerHTML)}
        onBlur={(event) => onChange(event.currentTarget.innerHTML)}
      />
    </div>
  )
}