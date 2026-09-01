import { z } from "zod";

export const loginSchema = z.object({
  email: z.email("Enter a valid email address."),
  password: z.string().min(1, "Password is required."),
  remember: z.boolean().optional(),
});

export const registerSchema = z
  .object({
    name: z.string().trim().min(1, "Name is required.").max(255),
    email: z.email("Enter a valid email address."),
    password: z.string().min(8, "Password must be at least 8 characters."),
    password_confirmation: z.string().min(1, "Confirm your password."),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: "Passwords do not match.",
    path: ["password_confirmation"],
  });

export type LoginFormValues = z.infer<typeof loginSchema>;
export type RegisterFormValues = z.infer<typeof registerSchema>;
