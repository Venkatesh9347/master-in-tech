import { createContext } from "react";

export type UserRole = "student" | "tutor" | "admin" | "super_admin" | "faculty" | "company" | "recruiter" | "counsellor";

export interface User {
  id: number;
  name: string;
  email: string;
  role?: UserRole | string;
  phone?: string;
  avatar?: string;
  created_at?: string;
  enrollments_count?: number;
  taught_courses_count?: number;
}

export interface GoogleAuthPendingSession {
  temp_token: string;
  email?: string;
  masked_email?: string;
  phone?: string;
  masked_phone?: string;
  expires_in: number;
  resend_cooldown: number;
}

export interface GoogleAuthPayload {
  credential?: string;
  code?: string;
  redirect_uri?: string;
}

export interface AuthContextValue {
  user: User | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<User>;
  initiateGoogleAuth: (payload: string | GoogleAuthPayload) => Promise<GoogleAuthPendingSession>;
  initiateMobileAuth: (phone: string) => Promise<GoogleAuthPendingSession>;
  verifyOtp: (tempToken: string, otp: string) => Promise<User>;
  resendOtp: (tempToken: string) => Promise<GoogleAuthPendingSession>;
  logout: () => Promise<void>;
}

export const AuthContext = createContext<AuthContextValue | undefined>(undefined);

/**
 * True when the current pathname belongs to a dashboard area whose session is
 * kept alive (and checked) by the AuthProvider heartbeat timer.
 */
export const isDashboardRoute = (pathname: string): boolean => {
  return (
    pathname.startsWith("/admin") ||
    pathname.startsWith("/tutor") ||
    pathname.startsWith("/student") ||
    pathname.startsWith("/company")
  );
};
