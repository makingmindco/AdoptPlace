import { passwordStrength } from '../utils/passwordStrength'

export default function PasswordStrength({ password }) {
  const { rules, score, label } = passwordStrength(password)
  return <div className="password-strength" id="password-requirements">
    <div className="strength-caption"><span>Força da senha</span><strong aria-live="polite">{label}</strong></div>
    <meter min="0" max="6" low="3" high="5" optimum="6" value={score} aria-label={`Força da senha: ${label}`} />
    <ul>{rules.map(rule => <li key={rule.label} className={rule.met ? 'is-met' : ''}>
      <span aria-hidden="true">{rule.met ? '✓' : '○'}</span><span className="sr-only">{rule.met ? 'Atendido: ' : 'Falta: '}</span>{rule.label}
    </li>)}</ul>
  </div>
}
