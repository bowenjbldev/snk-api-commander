import { Navigate, useParams } from 'react-router-dom';

/**
 * El evento completo es un diálogo sobre el listado, no una página: es auditoría de
 * cinco campos y no admite editar ni borrar. La ruta sobrevive porque la actividad
 * reciente del panel lateral enlaza a ella, y en vez de duplicar el diálogo lleva al
 * listado con el evento ya abierto.
 */
export function ActivityDetailPage() {
  const { id = '' } = useParams();
  return <Navigate to={`/activity?event=${encodeURIComponent(id)}`} replace />;
}
