import { SkeletonText } from '@carbon/react';
import { useNavigate, useParams } from 'react-router-dom';
import { useApp } from '@app/provider';
import { useResource } from '@shared/hooks/useResource';
import { FormPage } from '@shared/components/FormPage';
import { ErrorState, Forbidden } from '@shared/components/States';
import { emptyOperatorValues, operatorFormSections } from '../model/operatorForm';
import { useOperatorsService } from '../services/operatorsService';

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
      lede="Usuarios de Commander. Solo existe el rol admin sembrado: mientras no haya endpoint de roles, aquí no se inventa un selector."
      crumbs={[{ label: 'Operadores', to: '/operators' }, { label: editing ? 'Editar' : 'Nuevo' }]}
      cancelTo="/operators"
      submitLabel={editing ? 'Guardar cambios' : 'Crear operador'}
      initial={emptyOperatorValues(res.data)}
      sections={operatorFormSections(editing)}
      onSubmit={async (values) => {
        await operators.save(values, id ? Number(id) : undefined);
        navigate('/operators');
      }}
    />
  );
}
