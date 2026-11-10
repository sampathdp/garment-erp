export interface ScopeRef {
  id: number;
  code: string;
  name: string;
}

export interface RoleRef {
  name: string;
  label: string;
}

export interface CurrentUser {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  last_login_at: string | null;
  password_changed_at: string | null;
  roles: RoleRef[];
  permissions: string[];
  scope: {
    all_branches: boolean;
    all_pages: boolean;
    branches: ScopeRef[];
    pages: (ScopeRef & { is_owner: boolean })[];
  };
}
