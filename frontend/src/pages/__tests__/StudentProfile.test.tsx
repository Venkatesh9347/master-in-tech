import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor, cleanup, fireEvent } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";

const mocks = vi.hoisted(() => {
  const apiInstance = {
    interceptors: { request: { use: () => {} }, response: { use: () => {} } },
    get: vi.fn(),
    put: vi.fn(),
    post: vi.fn(),
  };
  return { apiInstance };
});

vi.mock("axios", () => ({
  default: { create: () => mocks.apiInstance },
}));

vi.mock("../../components/Navbar", () => ({ default: () => <div>NAVBAR</div> }));
vi.mock("../../components/Footer", () => ({ default: () => <div>FOOTER</div> }));
vi.mock("../../context/useAuth", () => ({
  useAuth: () => ({
    user: { id: 1, name: "Test Student", email: "student@example.com", phone: "9000000001", role: "student" },
  }),
}));

import StudentProfile from "../StudentProfile";

const profilePayload = {
  user: { name: "Test Student", phone: "9000000001", location: "Bengaluru", bio: "Aspiring engineer" },
};

function mockMount() {
  mocks.apiInstance.get.mockImplementation((url: string) => {
    if (url === "/student/profile") return Promise.resolve({ data: profilePayload });
    if (url === "/my-courses") return Promise.resolve({ data: [] });
    return Promise.resolve({ data: [] });
  });
}

describe("StudentProfile", () => {
  afterEach(() => cleanup());
  beforeEach(() => {
    mocks.apiInstance.get.mockReset();
    mocks.apiInstance.put.mockReset();
    mocks.apiInstance.post.mockReset();
    mockMount();
  });

  it("hydrates the form from GET /student/profile", async () => {
    const { container } = render(
      <MemoryRouter>
        <StudentProfile />
      </MemoryRouter>
    );

    await waitFor(() => expect(mocks.apiInstance.get).toHaveBeenCalledWith("/student/profile"));
    await waitFor(() => {
      const bio = container.querySelector("textarea");
      expect(bio?.textContent ?? (bio as HTMLTextAreaElement | null)?.value).toBe("Aspiring engineer");
    });
  });

  it("blocks submit when passwords do not match without calling the API", async () => {
    const { container } = render(
      <MemoryRouter>
        <StudentProfile />
      </MemoryRouter>
    );

    await waitFor(() => expect(mocks.apiInstance.get).toHaveBeenCalledWith("/student/profile"));

    const passwords = container.querySelectorAll('input[type="password"]');
    fireEvent.change(passwords[0], { target: { value: "newpass123" } });
    fireEvent.change(passwords[1], { target: { value: "different456" } });
    fireEvent.click(screen.getByRole("button", { name: /save profile settings/i }));

    await waitFor(() => expect(screen.getByText(/passwords do not match/i)).toBeTruthy());
    expect(mocks.apiInstance.put).not.toHaveBeenCalled();
  });

  it("submits PUT /student/profile with the correct payload and shows success", async () => {
    mocks.apiInstance.put.mockImplementation(() =>
      Promise.resolve({
        data: {
          message: "Profile updated successfully.",
          user: { name: "Test Student", location: "Bengaluru", bio: "Aspiring engineer" },
        },
      })
    );

    const { container } = render(
      <MemoryRouter>
        <StudentProfile />
      </MemoryRouter>
    );

    await waitFor(() => expect(mocks.apiInstance.get).toHaveBeenCalledWith("/student/profile"));

    const passwords = container.querySelectorAll('input[type="password"]');
    fireEvent.change(passwords[0], { target: { value: "newpass123" } });
    fireEvent.change(passwords[1], { target: { value: "newpass123" } });
    fireEvent.click(screen.getByRole("button", { name: /save profile settings/i }));

    await waitFor(() =>
      expect(mocks.apiInstance.put).toHaveBeenCalledWith(
        "/student/profile",
        expect.objectContaining({
          name: "Test Student",
          phone: "9000000001",
          location: "Bengaluru",
          bio: "Aspiring engineer",
          password: "newpass123",
        })
      )
    );
    await waitFor(() => expect(screen.getByText(/profile updated successfully/i)).toBeTruthy());
  });

  it("omits password when left blank and surfaces backend errors", async () => {
    mocks.apiInstance.put.mockImplementation(() =>
      Promise.reject({ response: { data: { message: "The phone field must not exceed 30 characters." } } })
    );

    render(
      <MemoryRouter>
        <StudentProfile />
      </MemoryRouter>
    );

    await waitFor(() => expect(mocks.apiInstance.get).toHaveBeenCalledWith("/student/profile"));
    fireEvent.click(screen.getByRole("button", { name: /save profile settings/i }));

    await waitFor(() =>
      expect(mocks.apiInstance.put).toHaveBeenCalledWith(
        "/student/profile",
        expect.not.objectContaining({ password: expect.anything() })
      )
    );
    await waitFor(() =>
      expect(screen.getByText(/must not exceed 30 characters/i)).toBeTruthy()
    );
  });
});
