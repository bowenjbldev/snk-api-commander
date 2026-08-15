import { Checkbox, Select, SelectItem } from '@carbon/react';
import { ROLES } from '@services/api/mock/commander-mock';
import { useApp } from '@app/provider';

/**
 * Arnés del prototipo, no producto: cambia el rol efectivo, la latencia de la API
 * simulada y fuerza un 500 en cada petición para ver los caminos de error.
 */
export function SimulationBar() {
  const { sim, setSim } = useApp();

  return (
    <div className="cmd-sim">
      <span className="cmd-sim__label">Simulación</span>

      <div className="cmd-sim__field">
        <Select
          id="sim-role"
          inline
          size="sm"
          labelText="Permisos"
          value={sim.role}
          onChange={(e) => setSim({ role: e.target.value })}
        >
          {Object.entries(ROLES).map(([key, role]) => (
            <SelectItem key={key} value={key} text={role.label} />
          ))}
        </Select>
      </div>

      <div className="cmd-sim__field">
        <Select
          id="sim-latency"
          inline
          size="sm"
          labelText="Latencia"
          value={sim.latency}
          onChange={(e) => setSim({ latency: e.target.value as 'normal' | 'slow' })}
        >
          <SelectItem value="normal" text="Normal" />
          <SelectItem value="slow" text="Lenta" />
        </Select>
      </div>

      <Checkbox
        id="sim-fail"
        labelText="Devolver 500 en cada petición"
        checked={sim.fail}
        onChange={(_e, { checked }) => setSim({ fail: checked })}
      />

      <span className="cmd-sim__hint">{ROLES[sim.role].hint}</span>
    </div>
  );
}
