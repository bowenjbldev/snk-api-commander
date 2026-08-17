import type { ReactNode } from 'react';
import { PageHeader } from './PageHeader';
import { Forbidden } from './States';
import { useApp } from '@app/provider';

/** Envoltura común de listado: permiso, cabecera única y cuerpo. */
export function ListPage({
  perm,
  title,
  lede,
  actions,
  children,
}: {
  perm: string;
  title: string;
  lede: string;
  actions?: ReactNode;
  children: ReactNode;
}) {
  const { can } = useApp();

  return (
    <>
      <PageHeader title={title} lede={lede} actions={can(perm) ? actions : undefined} />
      {can(perm) ? children : <Forbidden perm={perm} />}
    </>
  );
}
