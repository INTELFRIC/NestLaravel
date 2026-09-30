# @platform/api-client

Shared fetch client for portal UIs talking to the Laravel API.

```ts
import { createApiClient } from '@platform/api-client';

const api = createApiClient({
  baseUrl: process.env.NEXT_PUBLIC_API_URL!,
  getToken: () => localStorage.getItem('access_token'),
});

await api.login(email, password);
await api.me();
await api.logout();
```
