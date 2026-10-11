// Modul domain: Autentikasi & sesi pengguna
import { apiRequest } from "../api";
import type { User } from "../types";

export interface LoginPayload {
  email: string;
  password: string;
  remember?: boolean;
}

export const authApi = {
  /** GET /api/v1/auth/me — profil, peran, izin, dan shift aktif. */
  me: () => apiRequest<User>("/auth/me"),

  /** POST /api/v1/auth/login — memulai sesi Sanctum. */
  login: (payload: LoginPayload) => apiRequest<User>("/auth/login", { method: "POST", body: payload }),

  /** POST /api/v1/auth/logout — mengakhiri sesi. */
  logout: () => apiRequest<null>("/auth/logout", { method: "POST" }),

  /** PUT /api/v1/auth/profile — perbarui nama & telepon. */
  updateProfile: (payload: { name: string; phone?: string | null }) =>
    apiRequest<User>("/auth/profile", { method: "PUT", body: payload }),

  /** PUT /api/v1/auth/password — perbarui kata sandi. */
  updatePassword: (payload: { current_password: string; password: string; password_confirmation: string }) =>
    apiRequest<null>("/auth/password", { method: "PUT", body: payload }),
};