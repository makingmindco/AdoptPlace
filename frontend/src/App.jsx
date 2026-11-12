import {
  BrowserRouter,
  Routes,
  Route,
  Navigate,
  useLocation,
} from "react-router-dom";
import { useEffect } from "react";
import { FavoritesProvider } from "./context/FavoritesContext";
import { AuthProvider, useAuth } from "./context/AuthContext";
import { Header, Footer } from "./components/Layout";
import Home from "./pages/Home";
import Catalog from "./pages/Catalog";
import PetDetail from "./pages/PetDetail";
import Auth from "./pages/Auth";
import Register from "./pages/Register";
import Organization from "./pages/Organization";
import Account from "./pages/Account";
import Admin from "./pages/Admin";
import PasswordRecovery from "./pages/PasswordRecovery";
import Privacy from "./pages/Privacy";
import Dashboard from "./pages/Dashboard";
import "./styles-v2.css";
function ScrollToTop() {
  const { pathname, hash } = useLocation();
  useEffect(() => {
    if (hash)
      document
        .getElementById(hash.slice(1))
        ?.scrollIntoView({ behavior: "smooth" });
    else window.scrollTo(0, 0);
    document.title = "AdoptPlace · Um encontro que muda duas vidas";
  }, [pathname, hash]);
  return null;
}
function ProtectedRoute({ children }) {
  const { user, loading, loggingOut } = useAuth();
  const location = useLocation();
  if (loading) return <main className="container empty"><p>Carregando seu portal…</p></main>;
  if (user?.role === 'adopter') return <Navigate to="/animais" replace />;
  if (user?.role === 'admin') return <Navigate to="/administracao" replace />;
  return user ? children : <Navigate to="/login" replace state={loggingOut ? null : { from: `${location.pathname}${location.search}` }} />;
}
export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <FavoritesProvider>
        <ScrollToTop />
        <Header />
        <Routes>
          <Route path="/" element={<Home />} />
          <Route path="/animais" element={<Catalog />} />
          <Route path="/catalogo" element={<Navigate to="/animais" replace />} />
          <Route path="/pets/:id" element={<PetDetail />} />
          <Route path="/organizacoes/:slug" element={<Organization />} />
          <Route path="/login" element={<Auth key="login" />} />
          <Route path="/cadastro" element={<Register />} />
          <Route path="/minha-conta" element={<Account />} />
          <Route path="/privacidade" element={<Privacy />} />
          <Route path="/administracao" element={<Admin />} />
          <Route path="/recuperar-senha" element={<PasswordRecovery />} />
          <Route path="/redefinir-senha" element={<PasswordRecovery reset />} />
          <Route path="/dashboard" element={<ProtectedRoute><Dashboard /></ProtectedRoute>} />
          <Route path="/index.html" element={<Navigate to="/" replace />} />
          <Route
            path="/login.html"
            element={<Navigate to="/login" replace />}
          />
          <Route
            path="/dashboard.html"
            element={<Navigate to="/dashboard" replace />}
          />
          <Route
            path="/pet-detail.html"
            element={<Navigate to="/pets/pipoca" replace />}
          />
          <Route
            path="*"
            element={
              <main id="main" className="container empty">
                <h1>Página não encontrada</h1>
                <a className="button" href="/">
                  Voltar ao início
                </a>
              </main>
            }
          />
        </Routes>
        <Footer />
        </FavoritesProvider>
      </AuthProvider>
    </BrowserRouter>
  );
}
