import { Link } from 'react-router-dom'

export default function Privacy() {
  return <main id="main" className="container detail"><article className="panel"><h1>Seus dados e a adoção responsável</h1>
    <h2>Para criar sua conta</h2><p>Usamos nome, e-mail e telefone para administrar seu cadastro e permitir contato durante o processo de adoção. A senha é armazenada como hash. No cadastro de adotante, guardamos apenas CEP, cidade e estado do endereço.</p>
    <h2>ONGs e protetores</h2><p>Nome público, apresentação, cidade, estado e animais disponíveis aparecem no perfil após aprovação. O endereço completo é privado e criptografado. CNPJ, quando informado, auxilia a análise administrativa e não aparece no perfil público. E-mail e telefone só aparecem publicamente quando o responsável ativa essa opção no painel.</p>
    <h2>Solicitações e avaliações</h2><p>Ao enviar uma solicitação, seus dados e respostas são compartilhados com a organização responsável e com a administração para acompanhamento e moderação. Anotações internas da organização não são públicas. Avaliações de adoções concluídas podem ser publicadas após moderação, sem exibir seu e-mail, telefone ou endereço. Não inclua dados pessoais no comentário.</p>
    <h2>Serviços e armazenamento</h2><p>O CEP é enviado ao ViaCEP para buscar o endereço. Dados da conta e das adoções ficam no banco MongoDB. Cookies mantêm sua sessão; favoritos de visitantes ficam no navegador e favoritos de usuários conectados ficam na conta. E-mails de confirmação e recuperação são processados pelo serviço de e-mail configurado pela plataforma.</p>
    <h2>Participação</h2><p>O AdoptPlace é gratuito e destinado à adoção responsável. Informe dados verdadeiros, mantenha a situação dos animais atualizada e confirme uma adoção somente após receber o animal. A aprovação de um cadastro não substitui conversar com o responsável e conhecer as condições da adoção.</p>
    <p><Link className="button button-outline" to="/cadastro">Voltar ao cadastro</Link></p>
  </article></main>
}
