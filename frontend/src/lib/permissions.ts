import type { User, UserRole } from "../context/auth-context";

export type AccessArea = "admin" | "tutor" | "content" | "crm" | "company" | "student";

const AREAS: Record<AccessArea, UserRole[]> = {
  admin: ["super_admin", "admin"],
  tutor: ["super_admin", "admin", "tutor", "faculty"],
  content: ["super_admin", "admin", "tutor", "faculty"],
  crm: ["super_admin", "admin", "counsellor"],
  company: ["company", "recruiter"],
  student: ["student"],
};

const STAFF_AREAS: AccessArea[] = ["admin", "tutor", "content", "crm"];

function isSuperUser(role?: string): boolean {
  return role === "admin" || role === "super_admin";
}

export function canAccessArea(user: User | null, area: AccessArea): boolean {
  if (!user || !user.role) {
    return false;
  }

  if (AREAS[area].includes(user.role as UserRole)) {
    return true;
  }

  return isSuperUser(user.role) && STAFF_AREAS.includes(area);
}