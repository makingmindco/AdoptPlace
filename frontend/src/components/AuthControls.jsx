export function FormAlert({ message }) {
  return message ? <div className="form-alert" role="alert"><span aria-hidden="true">!</span><p>{message}</p></div> : null
}

export function SubmitButton({ busy, busyLabel, children }) {
  return <button type="submit" className="button full-width" disabled={busy} aria-label={busy ? busyLabel : children}>
    {busy && <span className="loading-spinner" aria-hidden="true" />}
    <span aria-live="polite">{busy ? busyLabel : children}</span>
  </button>
}
