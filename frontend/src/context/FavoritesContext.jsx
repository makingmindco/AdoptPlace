import { createContext, useContext, useState } from "react";
const FavoritesContext = createContext(null);
export function FavoritesProvider({ children }) {
  const [favorites, setFavorites] = useState(() => {
    try {
      const saved = JSON.parse(localStorage.getItem("adoptplace:favorites"));
      return Array.isArray(saved)
        ? saved.filter((id) => typeof id === "string")
        : [];
    } catch {
      return [];
    }
  });
  function toggle(id) {
    setFavorites((previous) => {
      const next = previous.includes(id)
        ? previous.filter((item) => item !== id)
        : [...previous, id];
      try {
        localStorage.setItem("adoptplace:favorites", JSON.stringify(next));
      } catch {
        /* Keep favorites in memory. */
      }
      return next;
    });
  }
  return (
    <FavoritesContext.Provider value={{ favorites, toggle }}>
      {children}
    </FavoritesContext.Provider>
  );
}
// eslint-disable-next-line react-refresh/only-export-components
export const useFavorites = () => useContext(FavoritesContext);
