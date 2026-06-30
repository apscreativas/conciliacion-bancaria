// Formateadores compartidos por las pantallas financieras (egresos, recurrentes…).

export const formatCurrency = (amount: number): string =>
    new Intl.NumberFormat("es-MX", { style: "currency", currency: "MXN" }).format(Number(amount));

// `fecha` llega como ISO (cast date → 'YYYY-MM-DDThh:mm:ss...Z'); tomar solo la parte de
// fecha y parsear como medianoche local evita el desfase de zona horaria y "Invalid Date".
export const formatDate = (d: string): string =>
    new Intl.DateTimeFormat("es-MX", { dateStyle: "medium" }).format(new Date(d.slice(0, 10) + "T00:00:00"));
