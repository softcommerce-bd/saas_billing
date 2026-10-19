import { z } from "zod";

const email = z.string().trim().min(1, "Enter your email address.").email("Enter a valid email address.");
const newPassword = z
  .string()
  .min(10, "Use at least 10 characters.")
  .regex(/[A-Za-z]/, "Include at least one letter.")
  .regex(/\d/, "Include at least one number.");

export const loginSchema = z.object({
  email,
  password: z.string().min(1, "Enter your password."),
  remember: z.boolean().optional(),
});

export const registerSchema = z
  .object({
    name: z.string().trim().min(2, "Enter your full name."),
    company_name: z.string().trim().min(2, "Enter your company name."),
    email,
    phone: z.string().trim().optional(),
    password: newPassword,
    password_confirmation: z.string(),
  })
  .refine((v) => v.password === v.password_confirmation, { path: ["password_confirmation"], message: "Passwords do not match." });

export const forgotSchema = z.object({ email });

export const resetSchema = z
  .object({ password: newPassword, password_confirmation: z.string() })
  .refine((v) => v.password === v.password_confirmation, { path: ["password_confirmation"], message: "Passwords do not match." });

export const invitedRegisterSchema = z
  .object({ name: z.string().trim().min(2, "Enter your full name."), password: newPassword, password_confirmation: z.string() })
  .refine((v) => v.password === v.password_confirmation, { path: ["password_confirmation"], message: "Passwords do not match." });

export type LoginValues = z.infer<typeof loginSchema>;
export type RegisterValues = z.infer<typeof registerSchema>;
