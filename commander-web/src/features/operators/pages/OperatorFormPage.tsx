import { SkeletonText } from '@carbon/react';
import { useNavigate, useParams } from 'react-router-dom';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { FormPage } from '@shared/components/FormPage';
import type { FormSection } from '@shared/components/FormPage';
import { ErrorState, Forbidden } from '@shared/components/States';
import { emptyOperatorValues } from '../model/operatorForm';
import { useOperatorsService } from '../services/operatorsService';

// Ni una etiqueta cambia entre alta y edición: lo único que se mueve es el rótulo del botón.
const sections: FormSection[] = [
  {
    legend: 'Identidad',
    fields: [
      { key: 'name', label: 'Nombre completo' },
      { key: 'short_name', label: 'Nombre corto' },
      { key: 'email', label: 'Correo', type: 'email' },
      { key: 'phone', label: 'Teléfono' },
    ],
  },
  {
    legend: 'Organización y acceso',
    fields: [
      { key: 'company', label: 'Empresa' },
      {
        key: 'role',
        label: 'Rol',
        readOnly: true,
        helper: 'Valor fijo: no hay catálogo de roles en la API.',
      },
      {
        key: 'password',
        label: 'Contraseña',
        type: 'password',
        helper: 'Nunca se muestra una contraseña ya almacenada.',
      },
    ],
  },
];

export function OperatorFormPage() {
  const { can } = useApp();
  const operators = useOperatorsService();
  const { id } = useParams();
  const navigate = useNavigate();
  const editing = Boolean(id);
  const perm = editing ? 'update users' : 'create users';

  const res = useResource(() => (id ? operators.get(id) : Promise.resolve(null)), [id]);

  if (!can(perm)) return <Forbidden perm={perm} />;
  if (res.error) return <ErrorState error={res.error} onRetry={res.reload} />;
  if (editing && (res.loading || !res.data)) return <SkeletonText paragraph lineCount={6} />;

  return (
    <FormPage
      title={editing ? 'Editar operador' : 'Nuevo operador'}
      lede="Como no existe endpoint de roles, el formulario usa el único rol sembrado: admin."
      crumbs={[{ label: 'Operadores', to: '/operators' }]}
      cancelTo="/operators"
      submitLabel={editing ? 'Guardar cambios' : 'Crear operador'}
      initial={emptyOperatorValues(res.data)}
      sections={sections}
      onSubmit={async (values) => {
        await operators.save(values, id ? Number(id) : undefined);
        navigate('/operators');
      }}
    />
  );
}
