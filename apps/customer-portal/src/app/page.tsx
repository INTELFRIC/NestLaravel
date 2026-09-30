'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import type { AuthUser } from '@platform/api-client';
import { api, getAccessToken, setAccessToken } from '@/lib/api';
import styles from './portal.module.css';

export default function HomePage() {
  const router = useRouter();
  const [user, setUser] = useState<AuthUser | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const token = getAccessToken();
    if (!token) {
      router.replace('/login');
      return;
    }

    api
      .me()
      .then((response) => setUser(response.data))
      .catch((err: Error) => {
        setError(err.message);
        setAccessToken(null);
        router.replace('/login');
      });
  }, [router]);

  async function logout() {
    try {
      await api.logout();
    } catch {
      // token may already be invalid
    }
    setAccessToken(null);
    router.replace('/login');
  }

  if (!user) {
    return (
      <main className={styles.shell}>
        <p className={styles.copy}>{error || 'Loading profile…'}</p>
      </main>
    );
  }

  return (
    <main className={styles.shell}>
      <section className={styles.card}>
        <p className={styles.eyebrow}>Customer Portal</p>
        <h1 className={styles.title}>Welcome, {user.name}</h1>
        <p className={styles.copy}>{user.email}</p>
        <p className={styles.meta}>Roles: {(user.roles || []).join(', ') || 'none'}</p>
        <button className={styles.button} type="button" onClick={logout}>
          Sign out
        </button>
      </section>
    </main>
  );
}
