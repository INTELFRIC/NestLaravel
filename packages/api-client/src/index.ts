export type ApiEnvelope<T> = {
  success: boolean;
  data: T;
  message: string | null;
  meta?: {
    request_id?: string;
    correlation_id?: string;
    [key: string]: unknown;
  };
  errors?: unknown;
};

export type AuthUser = {
  id: number | string;
  name: string;
  email: string;
  roles?: string[];
};

export type LoginResult = {
  token: string;
  user: AuthUser;
};

export type ApiClientOptions = {
  baseUrl: string;
  getToken?: () => string | null;
  onUnauthorized?: () => void;
};

export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
    public body?: unknown,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

export function createApiClient(options: ApiClientOptions) {
  const baseUrl = options.baseUrl.replace(/\/$/, '');

  async function request<T>(
    path: string,
    init: RequestInit = {},
  ): Promise<ApiEnvelope<T>> {
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');
    headers.set('Content-Type', 'application/json');

    const token = options.getToken?.();
    if (token) {
      headers.set('Authorization', `Bearer ${token}`);
    }

    const response = await fetch(`${baseUrl}${path}`, {
      ...init,
      headers,
    });

    const body = (await response.json().catch(() => null)) as ApiEnvelope<T> | null;

    if (response.status === 401) {
      options.onUnauthorized?.();
    }

    if (!response.ok) {
      throw new ApiError(
        body?.message || `Request failed (${response.status})`,
        response.status,
        body,
      );
    }

    if (!body) {
      throw new ApiError('Empty response', response.status);
    }

    return body;
  }

  return {
    login(email: string, password: string) {
      return request<LoginResult>('/auth/login', {
        method: 'POST',
        body: JSON.stringify({ email, password }),
      });
    },
    register(payload: { name: string; email: string; password: string; password_confirmation: string }) {
      return request<LoginResult>('/auth/register', {
        method: 'POST',
        body: JSON.stringify(payload),
      });
    },
    me() {
      return request<AuthUser>('/auth/me', { method: 'GET' });
    },
    logout() {
      return request<null>('/auth/logout', { method: 'POST' });
    },
  };
}

export type ApiClient = ReturnType<typeof createApiClient>;
